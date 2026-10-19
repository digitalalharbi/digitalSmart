import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatNumber } from '@/shared/lib/format';
import { InfoHint } from '@/shared/ui/info-hint';
import { Alert } from '@/shared/ui/alert';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  legend,
  readChartPalette,
  seriesColour,
} from '@/shared/ui/chart';
import type { ApprovalAgeInfo, ApprovalLoad } from './types';

type WaitingChartProps = {
  load: ApprovalLoad;
  ages: ApprovalAgeInfo[];
};

/**
 * Where the waiting is: requests waiting for a decision, beside approved changes waiting to be made.
 *
 * **Two series on one ordered axis.** Colour encodes which queue a bar belongs to — identity, so categorical
 * hues in their fixed order — while position encodes how long it has waited, which is the ordered scale. They
 * are **grouped, not stacked**: adding a request nobody has answered to a change nobody has made would produce
 * a total that describes neither problem, and the two have different owners.
 *
 * Drawing them together is the point. An agency looking at one queue sees a process that works; the second
 * series is where the approved-and-never-done backlog becomes visible, and an older second bar is somebody
 * being told a change was made that was not.
 */
export function WaitingChart({ load, ages }: WaitingChartProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const buckets = useMemo(
    () =>
      [...ages]
        .sort((a, b) => a.rank - b.rank)
        .map((age) => ({
          ...age,
          pending: load.pending_by_age[age.age] ?? 0,
          outstanding: load.outstanding_by_age[age.age] ?? 0,
        })),
    [ages, load.pending_by_age, load.outstanding_by_age],
  );

  const isEmpty = load.pending === 0 && load.outstanding === 0;

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);

    const series = [
      { key: 'pending' as const, name: t('approvals.chart.pending') },
      { key: 'outstanding' as const, name: t('approvals.chart.outstanding') },
    ];

    return {
      ...base,
      grid: { top: 30, bottom: 8, left: 8, right: 8, containLabel: true },
      legend: legend({ locale, isRtl }, palette, series.length),
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        formatter: (params: unknown) => {
          const points = params as { dataIndex: number }[];
          const bucket = buckets[points[0]?.dataIndex ?? 0];

          if (bucket === undefined) {
            return '';
          }

          return [
            `<strong>${bucket.label}</strong>`,
            `${t('approvals.chart.pending')}: ${formatNumber(bucket.pending, locale)}`,
            `${t('approvals.chart.outstanding')}: ${formatNumber(bucket.outstanding, locale)}`,
          ].join('<br/>');
        },
      },
      xAxis: {
        type: 'category',
        inverse: isRtl,
        data: buckets.map((bucket) => bucket.label),
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
        axisLabel: {
          color: palette.axisLabel,
          fontSize: 11,
          formatter: (value: number) => formatNumber(value, locale),
        },
      },
      series: series.map((definition, index) => ({
        ...barSeries(palette, {
          name: definition.name,
          data: buckets.map((bucket) => bucket[definition.key]),
          orientation: 'vertical',
          isRtl,
          colour: seriesColour(palette, index),
        }),
      })),
    };
  }, [buckets, isRtl, locale, t]);

  return (
    <div className="flex flex-col gap-3">
      {/*
        Outside the frame, so it survives the switch to the table. The sentence is about both representations,
        and it is the one a reader most needs: an approved change nobody has made is somebody believing
        something untrue, and the age says for how long.
      */}
      {load.outstanding > 0 ? (
        <Alert
          tone={
            load.oldest_outstanding_days !== null &&
            load.oldest_outstanding_days > load.patience_days
              ? 'warning'
              : 'info'
          }
        >
          <span className="flex flex-wrap items-center gap-1 font-medium">
            {t('approvals.chart.outstandingShort', {
              count: load.outstanding,
              days: load.oldest_outstanding_days ?? 0,
            })}
            <InfoHint
              text={t('approvals.chart.outstandingWarning', {
                count: load.outstanding,
                days: load.oldest_outstanding_days ?? 0,
              })}
            />
          </span>
        </Alert>
      ) : null}

      <ChartFrame
        title={t('approvals.chart.title')}
        subtitle={t('approvals.chart.subtitle')}
        caveats={[t('approvals.chart.caveatGrouped'), t('approvals.chart.caveatAge')]}
        isEmpty={isEmpty}
        emptyTitle={t('approvals.chart.emptyTitle')}
        emptyBody={t('approvals.chart.emptyBody')}
        table={{
          columns: [
            t('approvals.chart.age'),
            t('approvals.chart.pending'),
            t('approvals.chart.outstanding'),
          ],
          rows: buckets.map((bucket) => ({
            key: bucket.age,
            label: bucket.label,
            cells: [formatNumber(bucket.pending, locale), formatNumber(bucket.outstanding, locale)],
          })),
        }}
      >
        <Chart option={option} height={240} ariaLabel={t('approvals.chart.aria')}>
          <ul>
            {buckets.map((bucket) => (
              <li key={bucket.age}>
                {bucket.label}: {t('approvals.chart.pending')}{' '}
                {formatNumber(bucket.pending, locale)}, {t('approvals.chart.outstanding')}{' '}
                {formatNumber(bucket.outstanding, locale)}
              </li>
            ))}
          </ul>
        </Chart>
      </ChartFrame>
    </div>
  );
}
