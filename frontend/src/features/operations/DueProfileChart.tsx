import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatNumber, formatRate } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  readChartPalette,
  stepColour,
} from '@/shared/ui/chart';
import type { DueHorizonInfo, TaskWorkload } from './types';

type DueProfileChartProps = {
  workload: TaskWorkload;
  horizons: DueHorizonInfo[];
};

/**
 * When the open work is due — including the work nobody has dated.
 *
 * The **ordinal** ramp rather than categorical hues, because the buckets are an ordered scale: swapping two
 * of them would change the meaning. Categorical colours would say "overdue" and "later" are unrelated kinds.
 *
 * The chart's whole reason for existing is its last bar. A board that folded undated work into "later" would
 * draw a reassuring profile while a third of its work could not be late, and the overdue figure beside it
 * would be read as the amount of lateness rather than as the amount of lateness *among the dated work*. So
 * undated is its own bar, it is counted in its own figure, and when it is large enough to matter the alert
 * above the chart says the overdue count cannot be read the way a reader expects.
 *
 * Closed work is left out entirely. Finished work that was late is history, and including it would leave an
 * overdue count nobody could ever clear.
 */
export function DueProfileChart({ workload, horizons }: DueProfileChartProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const buckets = useMemo(
    () =>
      [...horizons]
        .sort((a, b) => a.rank - b.rank)
        .map((horizon) => ({
          ...horizon,
          count: workload.by_horizon[horizon.horizon] ?? 0,
        })),
    [horizons, workload.by_horizon],
  );

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);

    return {
      ...base,
      grid: { top: 8, bottom: 8, left: 8, right: 8, containLabel: true },
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'item',
        formatter: (params: unknown) => {
          const point = params as { dataIndex: number };
          const bucket = buckets[point.dataIndex];

          return bucket === undefined
            ? ''
            : `${bucket.label}<br/><strong>${formatNumber(bucket.count, locale)}</strong>`;
        },
      },
      xAxis: { type: 'value', inverse: isRtl, show: false },
      yAxis: {
        type: 'category',
        position: isRtl ? 'right' : 'left',
        data: buckets.map((bucket) => bucket.label),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.textSecondary, fontSize: 12 },
      },
      series: [
        {
          ...barSeries(palette, {
            name: t('operations.due.title'),
            data: buckets.map((bucket) => bucket.count),
            orientation: 'horizontal',
            isRtl,
            /*
             * Darkest at the soonest end, so the eye lands on what is late. The ramp is walked in the
             * buckets' own order, which is the order the colour is carrying.
             */
            colours: buckets.map((_, index) =>
              stepColour(palette, buckets.length - 1 - index, buckets.length),
            ),
          }),
          label: {
            show: true,
            position: isRtl ? 'left' : 'right',
            color: palette.textSecondary,
            fontSize: 11,
            formatter: (params: unknown) =>
              formatNumber((params as { value: number }).value, locale),
          },
        },
      ],
    };
  }, [buckets, isRtl, locale, t]);

  return (
    <div className="flex flex-col gap-3">
      {/*
        Outside the frame, so it survives the switch to the table. It is a statement about both
        representations, and a reader who has switched to the table has not stopped needing it.
      */}
      {workload.open > 0 && !workload.is_lateness_reportable ? (
        <Alert tone="warning">
          {t('operations.due.notReportable', {
            overdue: formatNumber(workload.overdue, locale),
            undated: formatNumber(workload.undated, locale),
            share: workload.dated_share === null ? '—' : formatRate(workload.dated_share, locale),
            floor: formatRate(workload.dated_share_floor, locale),
          })}
        </Alert>
      ) : null}

      <ChartFrame
        title={t('operations.due.title')}
        subtitle={t('operations.due.subtitle')}
        caveats={[t('operations.due.caveatClosed'), t('operations.due.caveatUndated')]}
        isEmpty={workload.open === 0}
        emptyTitle={t('operations.due.emptyTitle')}
        emptyBody={t('operations.due.emptyBody')}
        table={{
          columns: [t('operations.due.bucket'), t('operations.due.count')],
          rows: buckets.map((bucket) => ({
            key: bucket.horizon,
            label: bucket.label,
            cells: [formatNumber(bucket.count, locale)],
          })),
        }}
      >
        <Chart option={option} height={200} ariaLabel={t('operations.due.aria')}>
          <ul>
            {buckets.map((bucket) => (
              <li key={bucket.horizon}>
                {bucket.label}: {formatNumber(bucket.count, locale)}
              </li>
            ))}
          </ul>
        </Chart>
      </ChartFrame>
    </div>
  );
}
