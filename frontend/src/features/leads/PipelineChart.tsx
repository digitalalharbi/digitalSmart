import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatNumber, formatRate } from '@/shared/lib/format';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  readChartPalette,
  stepColour,
} from '@/shared/ui/chart';
import type { LeadFunnel } from './types';

type PipelineChartProps = {
  funnel: LeadFunnel;
  windowDays: number;
};

/**
 * How far the leads that arrived actually got.
 *
 * **Four steps, and deliberately not five.** Each one is a genuine superset of the next —
 * everyone reached was someone a person tried to reach, and everyone won was reached — so
 * the bars can only shorten, which is what makes a funnel readable at a glance.
 *
 * "Qualified" is not a step here, and leaving it out is the point. The server reports the
 * leads *currently* at that stage, and a lead that has since been won passed through it
 * without being counted in it; putting that number between "reached" and "won" would show
 * a funnel narrowing and then widening, and a reader would reasonably conclude leads were
 * being won without qualification. It is shown beside the chart as what it is: a count of
 * where the pipeline stands now.
 *
 * The bars use the ordinal ramp rather than eight categorical hues, because swapping two
 * of these stages would change the meaning — the order is the information.
 */
export function PipelineChart({ funnel, windowDays }: PipelineChartProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const steps = useMemo(
    () => [
      {
        key: 'arrived',
        label: t('leads.pipeline.arrived'),
        count: funnel.total,
        /*
         * The first step has no rate: there is nothing above it to be a share of. A "100%"
         * here would be arithmetic dressed as a finding.
         */
        rate: null as number | null,
        rateNote: null as string | null,
      },
      {
        key: 'attempted',
        label: t('leads.pipeline.attempted'),
        // Contacted is an attempt; reached means it worked. Both were attempted.
        count: funnel.contacted + funnel.reached,
        rate: funnel.contact_rate,
        rateNote: t('leads.pipeline.ofArrived'),
      },
      {
        key: 'reached',
        label: t('leads.pipeline.reached'),
        count: funnel.reached,
        rate: null,
        rateNote: null,
      },
      {
        key: 'won',
        label: t('leads.pipeline.won'),
        count: funnel.won,
        rate: funnel.win_rate,
        rateNote: t('leads.pipeline.ofClosed'),
      },
    ],
    [funnel, t],
  );

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);

    // Bottom-up on a category axis, so the first stage goes last to sit at the top.
    const ordered = [...steps].reverse();

    return {
      ...base,
      grid: { top: 8, bottom: 8, left: 8, right: 8, containLabel: true },
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'item',
        formatter: (params: unknown) => {
          const point = params as { dataIndex: number };
          const step = ordered[point.dataIndex];

          if (step === undefined) {
            return '';
          }

          const rate =
            step.rate === null
              ? ''
              : `<br/>${formatRate(step.rate, locale)} ${step.rateNote ?? ''}`;

          return `${step.label}<br/><strong>${formatNumber(step.count, locale)}</strong>${rate}`;
        },
      },
      xAxis: {
        type: 'value',
        inverse: isRtl,
        show: false,
        // Anchored to the pipeline's own top, so the bars are shares of what arrived rather
        // than of the largest bar — which is the same thing here, and will not always be.
        max: Math.max(funnel.total, 1),
      },
      yAxis: {
        type: 'category',
        position: isRtl ? 'right' : 'left',
        data: ordered.map((step) => step.label),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.textSecondary, fontSize: 12 },
      },
      series: [
        {
          ...barSeries(palette, {
            name: t('leads.pipeline.title'),
            data: ordered.map((step) => step.count),
            orientation: 'horizontal',
            isRtl,
            // Darkest at the narrow end: the reader's eye follows the deepening colour down.
            colours: ordered.map((_, index) =>
              stepColour(palette, ordered.length - 1 - index, ordered.length),
            ),
          }),
          /*
           * The count at the tip of each bar, outside the mark. Four rows is few enough that
           * a label on every one is readable rather than noise, and outside means a short bar
           * never clips its own number.
           */
          label: {
            show: true,
            position: isRtl ? 'left' : 'right',
            color: palette.textSecondary,
            fontSize: 11,
            formatter: (params: unknown) => {
              const point = params as { dataIndex: number };
              const step = ordered[point.dataIndex];

              return step === undefined ? '' : formatNumber(step.count, locale);
            },
          },
        },
      ],
    };
  }, [steps, funnel.total, isRtl, locale, t]);

  return (
    <ChartFrame
      title={t('leads.pipeline.title')}
      subtitle={t('leads.pipeline.subtitle', { days: windowDays })}
      caveats={[
        /*
         * In the response's own spirit: the reason a stage is missing belongs next to the
         * chart, because the person reading it is the one who would otherwise wonder.
         */
        t('leads.pipeline.whyNoQualified'),
      ]}
      isEmpty={funnel.total === 0}
      emptyTitle={t('leads.pipeline.emptyTitle')}
      emptyBody={t('leads.pipeline.emptyBody', { days: windowDays })}
      table={{
        columns: [
          t('leads.table.stage'),
          t('leads.table.leads'),
          t('leads.table.rate'),
          t('leads.table.rateOf'),
        ],
        rows: steps.map((step) => ({
          key: step.key,
          label: step.label,
          cells: [
            formatNumber(step.count, locale),
            // A rate with no denominator is not a rate, so it is absent rather than zero.
            step.rate === null ? '—' : formatRate(step.rate, locale),
            step.rateNote ?? '—',
          ],
        })),
      }}
    >
      <Chart
        option={option}
        height={220}
        ariaLabel={t('leads.pipeline.aria', { days: windowDays })}
      >
        <ul>
          {steps.map((step) => (
            <li key={step.key}>
              {step.label}: {formatNumber(step.count, locale)}
              {step.rate === null
                ? ''
                : ` (${formatRate(step.rate, locale)} ${step.rateNote ?? ''})`}
            </li>
          ))}
        </ul>
      </Chart>
    </ChartFrame>
  );
}
