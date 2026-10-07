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
import type { IntakeLoad, UrgencyInfo } from './types';

type StatedUrgencyChartProps = {
  load: IntakeLoad;
  urgencies: UrgencyInfo[];
};

/**
 * How urgent the **clients** said their open requests were.
 *
 * The ordinal ramp, because urgency is an ordered scale. The title and every label say "the client said", and
 * that is the whole point of the chart: it is a picture of what clients are telling this agency, not a picture of
 * what the agency has decided to do first. The two are allowed to differ, and a chart that blurred them would let
 * a client set an agency's priorities by typing.
 *
 * The tasks raised from these requests carry the agency's own priority, on its own board, with its own chart.
 */
export function StatedUrgencyChart({ load, urgencies }: StatedUrgencyChartProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const levels = useMemo(
    () =>
      [...urgencies]
        .sort((a, b) => a.rank - b.rank)
        .map((level) => ({ ...level, count: load.by_urgency[level.urgency] ?? 0 })),
    [urgencies, load.by_urgency],
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
          const level = levels[point.dataIndex];

          return level === undefined
            ? ''
            : `${level.label}<br/><strong>${formatNumber(level.count, locale)}</strong>`;
        },
      },
      xAxis: {
        type: 'category',
        inverse: isRtl,
        data: levels.map((level) => level.label),
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
      series: [
        {
          ...barSeries(palette, {
            name: t('requests.urgency.title'),
            data: levels.map((level) => level.count),
            orientation: 'vertical',
            isRtl,
            // Darkening as the client's stated urgency rises, which is the order the scale carries.
            colours: levels.map((_, index) => stepColour(palette, index, levels.length)),
          }),
        },
      ],
    };
  }, [levels, isRtl, locale, t]);

  return (
    <ChartFrame
      title={t('requests.urgency.title')}
      subtitle={t('requests.urgency.subtitle')}
      caveats={[t('requests.urgency.caveat')]}
      isEmpty={load.open === 0}
      emptyTitle={t('requests.urgency.emptyTitle')}
      emptyBody={t('requests.urgency.emptyBody')}
      table={{
        columns: [t('requests.urgency.level'), t('requests.urgency.count')],
        rows: levels.map((level) => ({
          key: level.urgency,
          label: level.label,
          cells: [formatNumber(level.count, locale)],
        })),
      }}
    >
      <Chart option={option} height={200} ariaLabel={t('requests.urgency.aria')}>
        <ul>
          {levels.map((level) => (
            <li key={level.urgency}>
              {level.label}: {formatNumber(level.count, locale)}
            </li>
          ))}
        </ul>
      </Chart>
    </ChartFrame>
  );
}
