import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatNumber } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  readChartPalette,
  stepColour,
} from '@/shared/ui/chart';
import type { IntakeLoad, RequestStateInfo } from './types';

type IntakeFlowChartProps = {
  load: IntakeLoad;
  states: RequestStateInfo[];
};

/**
 * Where the open requests are sitting.
 *
 * The **ordinal** ramp, in board order, because the states are stages one conversation passes through: swapping
 * two of them would change the meaning, so the order belongs in the colour.
 *
 * The agency/client split is **not** encoded in colour, deliberately. Colour is already carrying the order, and a
 * second meaning in the same channel is how a reader learns to distrust a palette. It is carried instead by the
 * caveat and by the two figures above the chart — which is also where it belongs, because it is a comparison of
 * two totals rather than a shape.
 *
 * Closed states are left out. A delivered request is not where work is sitting, and a column of them would make
 * a productive month look like a backlog.
 */
export function IntakeFlowChart({ load, states }: IntakeFlowChartProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const stages = useMemo(
    () =>
      [...states]
        .filter((state) => state.is_open)
        .sort((a, b) => a.position - b.position)
        .map((state) => ({ ...state, count: load.by_state[state.state] ?? 0 })),
    [states, load.by_state],
  );

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);
    // Reversed, because a category axis draws its first entry at the bottom.
    const drawn = [...stages].reverse();

    return {
      ...base,
      grid: { top: 8, bottom: 8, left: 8, right: 8, containLabel: true },
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'item',
        formatter: (params: unknown) => {
          const point = params as { dataIndex: number };
          const stage = drawn[point.dataIndex];

          return stage === undefined
            ? ''
            : `${stage.label}<br/><strong>${formatNumber(stage.count, locale)}</strong>`;
        },
      },
      xAxis: { type: 'value', inverse: isRtl, show: false },
      yAxis: {
        type: 'category',
        position: isRtl ? 'right' : 'left',
        data: drawn.map((stage) => stage.label),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.textSecondary, fontSize: 12, width: 160, overflow: 'truncate' },
      },
      series: [
        {
          ...barSeries(palette, {
            name: t('requests.flow.title'),
            data: drawn.map((stage) => stage.count),
            orientation: 'horizontal',
            isRtl,
            // Darkening along the process, so the eye follows a conversation through it.
            colours: drawn.map((_, index) =>
              stepColour(palette, drawn.length - 1 - index, drawn.length),
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
  }, [stages, isRtl, locale, t]);

  return (
    <div className="flex flex-col gap-3">
      {/*
        Outside the frame, so it survives the switch to the table. A client who has waited longer than this
        product's stated patience has been ignored, and that is a different statement from "the queue is busy".
      */}
      {load.oldest_unanswered_days !== null && load.oldest_unanswered_days > load.patience_days ? (
        <Alert tone="warning">
          {t('requests.flow.ignored', {
            days: load.oldest_unanswered_days,
            patience: load.patience_days,
          })}
        </Alert>
      ) : null}

      <ChartFrame
        title={t('requests.flow.title')}
        subtitle={t('requests.flow.subtitle')}
        caveats={[t('requests.flow.caveatSplit'), t('requests.flow.caveatClosed')]}
        isEmpty={load.open === 0}
        emptyTitle={t('requests.flow.emptyTitle')}
        emptyBody={t('requests.flow.emptyBody')}
        table={{
          columns: [
            t('requests.flow.stage'),
            t('requests.flow.count'),
            t('requests.flow.waitingOn'),
          ],
          rows: stages.map((stage) => ({
            key: stage.state,
            label: stage.label,
            cells: [
              formatNumber(stage.count, locale),
              /*
                Said in the table rather than drawn in colour. It is the fact a reader most needs and the one a
                second colour meaning would have muddled.
              */
              t(stage.is_on_the_client ? 'requests.flow.theClient' : 'requests.flow.theAgency'),
            ],
          })),
        }}
      >
        <Chart option={option} height={200} ariaLabel={t('requests.flow.aria')}>
          <ul>
            {stages.map((stage) => (
              <li key={stage.state}>
                {stage.label}: {formatNumber(stage.count, locale)} —{' '}
                {t(stage.is_on_the_client ? 'requests.flow.theClient' : 'requests.flow.theAgency')}
              </li>
            ))}
          </ul>
        </Chart>
      </ChartFrame>
    </div>
  );
}
