import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatNumber } from '@/shared/lib/format';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  readChartPalette,
  stepColour,
} from '@/shared/ui/chart';
import type { CreativeBoard } from './types';

type WorkloadChartProps = {
  board: CreativeBoard;
};

/**
 * Where the work sits.
 *
 * The **ordinal** ramp, not eight categorical hues, because the board's columns are stages of one process:
 * swapping two of them would change the meaning, so the order belongs in the colour. A categorical palette here
 * would say the stages are unrelated categories, which is the opposite of what a board is.
 *
 * Cancelled is left out. It is not a stage work passes through, and including it would make a month with one
 * abandoned brief look like a month with a bottleneck at the end.
 */
export function WorkloadChart({ board }: WorkloadChartProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const stages = useMemo(
    () => board.columns.filter((column) => column.state !== 'cancelled'),
    [board.columns],
  );

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);
    const ordered = [...stages].reverse();

    return {
      ...base,
      grid: { top: 8, bottom: 8, left: 8, right: 8, containLabel: true },
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'item',
        formatter: (params: unknown) => {
          const point = params as { dataIndex: number };
          const column = ordered[point.dataIndex];

          return column === undefined
            ? ''
            : `${column.label}<br/><strong>${formatNumber(column.requests.length, locale)}</strong>`;
        },
      },
      xAxis: { type: 'value', inverse: isRtl, show: false },
      yAxis: {
        type: 'category',
        position: isRtl ? 'right' : 'left',
        data: ordered.map((column) => column.label),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.textSecondary, fontSize: 12 },
      },
      series: [
        {
          ...barSeries(palette, {
            name: t('creative.workload.title'),
            data: ordered.map((column) => column.requests.length),
            orientation: 'horizontal',
            isRtl,
            // Darkening towards the end of the process, so the eye follows the work along it.
            colours: ordered.map((_, index) =>
              stepColour(palette, ordered.length - 1 - index, ordered.length),
            ),
          }),
          label: {
            show: true,
            position: isRtl ? 'left' : 'right',
            color: palette.textSecondary,
            fontSize: 11,
            formatter: (params: unknown) => {
              const point = params as { dataIndex: number };
              const column = ordered[point.dataIndex];

              return column === undefined ? '' : formatNumber(column.requests.length, locale);
            },
          },
        },
      ],
    };
  }, [stages, isRtl, locale, t]);

  const total = stages.reduce((sum, column) => sum + column.requests.length, 0);

  return (
    <ChartFrame
      title={t('creative.workload.title')}
      subtitle={t('creative.workload.subtitle')}
      caveats={[t('creative.workload.caveat')]}
      isEmpty={total === 0}
      emptyTitle={t('creative.workload.emptyTitle')}
      emptyBody={t('creative.workload.emptyBody')}
      table={{
        columns: [t('creative.workload.stage'), t('creative.workload.count')],
        rows: stages.map((column) => ({
          key: column.state,
          label: column.label,
          cells: [formatNumber(column.requests.length, locale)],
        })),
      }}
    >
      <Chart option={option} height={220} ariaLabel={t('creative.workload.aria')}>
        <ul>
          {stages.map((column) => (
            <li key={column.state}>
              {column.label}: {formatNumber(column.requests.length, locale)}
            </li>
          ))}
        </ul>
      </Chart>
    </ChartFrame>
  );
}
