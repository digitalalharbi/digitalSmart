import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatNumber } from '@/shared/lib/format';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  legend,
  readChartPalette,
  stepColour,
} from '@/shared/ui/chart';
import type { AssigneeLoad, TaskPriorityInfo, TaskPriorityValue } from './types';

type LoadByPersonChartProps = {
  load: AssigneeLoad[];
  priorities: TaskPriorityInfo[];
  /** The priorities actually on the board, so the legend carries no entry for urgency nothing has. */
  present: TaskPriorityValue[];
};

/** Enough rows to see the shape of a team without a chart nobody can read. */
const ROW_CAP = 10;

/**
 * Who is carrying the open work, and what kind of work it is.
 *
 * **Stacked**, which is the one place in this product where stacking is right: the segments of a person's bar
 * are parts of their own open load, and the total they add up to is a real figure somebody is accountable
 * for. The attribution comparison next door is grouped for the opposite reason — those bars are alternative
 * accounts of the same money, and their sum exists nowhere.
 *
 * Stacked by **priority**, on the ordinal ramp, because priority is an ordered scale. Categorical hues would
 * say urgent and low are unrelated kinds of thing.
 *
 * The unassigned pile is a row like any other, sorted last however big it is. Leaving it out would be the
 * flattering version — a comfortable team drawn beside a stack of work nobody has agreed to do — and
 * leading with it would read as somebody called "Unassigned" being overworked.
 */
export function LoadByPersonChart({ load, priorities, present }: LoadByPersonChartProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const ordered = useMemo(() => [...priorities].sort((a, b) => a.rank - b.rank), [priorities]);

  const plotted = useMemo(
    () => ordered.filter((priority) => present.length === 0 || present.includes(priority.priority)),
    [ordered, present],
  );

  /*
   * Capped rather than folded into "Other". A workload chart is read to find out who to talk to, and a bar
   * labelled "Other" names nobody — so the rows beyond the cap are left to the table, which has all of them,
   * and the frame says how many are not drawn.
   */
  const rows = useMemo(() => load.slice(0, ROW_CAP), [load]);
  const hidden = load.length - rows.length;

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);
    // Reversed, because a category axis draws its first entry at the bottom.
    const drawn = [...rows].reverse();

    return {
      ...base,
      grid: { top: 30, bottom: 8, left: 8, right: 8, containLabel: true },
      legend: legend({ locale, isRtl }, palette, plotted.length),
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        formatter: (params: unknown) => {
          const points = params as { dataIndex: number }[];
          const row = drawn[points[0]?.dataIndex ?? 0];

          if (row === undefined) {
            return '';
          }

          const name = row.is_unassigned ? t('operations.load.unassigned') : (row.name ?? '—');
          const lines = [`<strong>${name}</strong>`];

          plotted.forEach((priority) => {
            lines.push(
              `${priority.label}: ${formatNumber(row.by_priority[priority.priority] ?? 0, locale)}`,
            );
          });

          lines.push(`${t('operations.load.overdue')}: ${formatNumber(row.overdue, locale)}`);

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
          formatter: (value: number) => formatNumber(value, locale),
        },
      },
      yAxis: {
        type: 'category',
        position: isRtl ? 'right' : 'left',
        data: drawn.map((row) =>
          row.is_unassigned ? t('operations.load.unassigned') : (row.name ?? '—'),
        ),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.axisLabel, fontSize: 11, width: 150, overflow: 'truncate' },
      },
      series: plotted.map((priority, index) => ({
        ...barSeries(palette, {
          name: priority.label,
          data: drawn.map((row) => row.by_priority[priority.priority] ?? 0),
          orientation: 'horizontal',
          isRtl,
          stack: 'load',
          // Darkening as priority rises, so the heavy end of each bar is the urgent end.
          colour: stepColour(palette, index, plotted.length),
        }),
      })),
    };
  }, [rows, plotted, isRtl, locale, t]);

  return (
    <ChartFrame
      title={t('operations.load.title')}
      subtitle={t('operations.load.subtitle')}
      caveats={[
        t('operations.load.caveatOpen'),
        ...(hidden > 0 ? [t('operations.load.caveatHidden', { count: hidden })] : []),
      ]}
      isEmpty={load.length === 0}
      emptyTitle={t('operations.load.emptyTitle')}
      emptyBody={t('operations.load.emptyBody')}
      table={{
        columns: [
          t('operations.load.person'),
          ...ordered.map((priority) => priority.label),
          t('operations.load.open'),
          t('operations.load.overdue'),
        ],
        // The table carries every row, including the ones past the chart's cap.
        rows: load.map((row) => ({
          key: row.user_id ?? 'unassigned',
          label: row.is_unassigned ? t('operations.load.unassigned') : (row.name ?? '—'),
          cells: [
            ...ordered.map((priority) =>
              formatNumber(row.by_priority[priority.priority] ?? 0, locale),
            ),
            formatNumber(row.open, locale),
            formatNumber(row.overdue, locale),
          ],
        })),
      }}
    >
      <Chart
        option={option}
        height={Math.max(200, rows.length * 42 + 60)}
        ariaLabel={t('operations.load.aria')}
      >
        <ul>
          {load.map((row) => (
            <li key={row.user_id ?? 'unassigned'}>
              {row.is_unassigned ? t('operations.load.unassigned') : (row.name ?? '—')}:{' '}
              {formatNumber(row.open, locale)} ({t('operations.load.overdue')}{' '}
              {formatNumber(row.overdue, locale)})
            </li>
          ))}
        </ul>
      </Chart>
    </ChartFrame>
  );
}
